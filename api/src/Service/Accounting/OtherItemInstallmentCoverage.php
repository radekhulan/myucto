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
 *
 * Rozdělená splátka (migrace 2241) má jistinu, úrok a poplatek. Úhrada nese jistinu
 * v `amount` a úrok s poplatkem zvlášť, pravidlo výše platí pro každou složku samostatně
 * (úrok úhrady pokrývá úroky splátek, jistina jistiny). Nerozdělená splátka je celá
 * jistina, takže se počítá přesně jako dřív. Mimo evidenci vypořádaná částka
 * (`settled_outside_amount`) snižuje saldo položky, je to tedy jistina.
 *
 * `amount`, `paid_amount` a `remaining_amount` jsou peněžní částky celé splátky (kolik
 * se platí, předpověď je ukazuje celé); `remaining_principal` je zbytek jistiny, o který
 * se snižuje saldo položky.
 */
final class OtherItemInstallmentCoverage
{
    public const STATUS_PAID = 'paid';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_UNPAID = 'unpaid';

    /** Složky splátky v pořadí, v jakém je úhrada pokrývá: úrok, poplatek, jistina. */
    public const COMPONENTS = ['interest', 'fee', 'principal'];

    /**
     * @param list<array{id:int|string,position:int|string,due_on:string,amount:float|int|string,
     *     principal_amount?:float|int|string|null,interest_amount?:float|int|string|null,
     *     fee_amount?:float|int|string|null}> $installments
     * @param list<array{installment_id:int|string|null,amount:float|int|string,
     *     interest_amount?:float|int|string|null,fee_amount?:float|int|string|null}> $allocations aktivní
     *        úhrady v pořadí payment_on, id; `amount` je jistina
     * @return list<array{id:int,position:int,due_on:string,amount:float,principal_amount:?float,
     *     interest_amount:?float,fee_amount:?float,paid_amount:float,remaining_amount:float,
     *     remaining_principal:float,remaining_interest:float,remaining_fee:float,status:string}>
     */
    public static function compute(array $installments, array $allocations, float $settledOutside, string $today): array
    {
        usort($installments, static fn (array $a, array $b): int => (int) $a['position'] <=> (int) $b['position']);
        $open = [];
        $paid = [];
        $byId = [];
        foreach ($installments as $index => $row) {
            $open[$index] = self::parts($row);
            $paid[$index] = 0;
            $byId[(int) $row['id']] = $index;
        }
        $pool = ['interest' => 0, 'fee' => 0, 'principal' => self::cents($settledOutside)];
        foreach ($allocations as $allocation) {
            $amounts = [
                'interest' => self::cents($allocation['interest_amount'] ?? 0),
                'fee' => self::cents($allocation['fee_amount'] ?? 0),
                'principal' => self::cents($allocation['amount']),
            ];
            $target = $allocation['installment_id'] !== null ? ($byId[(int) $allocation['installment_id']] ?? null) : null;
            foreach (self::COMPONENTS as $component) {
                if ($target !== null) {
                    $take = min($amounts[$component], $open[$target][$component]);
                    $open[$target][$component] -= $take;
                    $paid[$target] += $take;
                    $amounts[$component] -= $take;
                }
                $pool[$component] += $amounts[$component];
            }
        }
        foreach (self::COMPONENTS as $component) {
            foreach (array_keys($installments) as $index) {
                if ($pool[$component] <= 0) break;
                $take = min($pool[$component], $open[$index][$component]);
                $open[$index][$component] -= $take;
                $paid[$index] += $take;
                $pool[$component] -= $take;
            }
        }
        $result = [];
        foreach ($installments as $index => $row) {
            $dueOn = (string) $row['due_on'];
            $remaining = array_sum($open[$index]);
            $status = match (true) {
                $remaining <= 0 => self::STATUS_PAID,
                $dueOn < $today => self::STATUS_OVERDUE,
                $paid[$index] > 0 => self::STATUS_PARTIAL,
                default => self::STATUS_UNPAID,
            };
            $split = self::isSplit($row);
            $result[] = [
                'id' => (int) $row['id'],
                'position' => (int) $row['position'],
                'due_on' => $dueOn,
                'amount' => self::cents($row['amount']) / 100.0,
                'principal_amount' => $split ? self::cents($row['principal_amount']) / 100.0 : null,
                'interest_amount' => $split ? self::cents($row['interest_amount']) / 100.0 : null,
                'fee_amount' => $split ? self::cents($row['fee_amount']) / 100.0 : null,
                'paid_amount' => $paid[$index] / 100.0,
                'remaining_amount' => $remaining / 100.0,
                'remaining_principal' => $open[$index]['principal'] / 100.0,
                'remaining_interest' => $open[$index]['interest'] / 100.0,
                'remaining_fee' => $open[$index]['fee'] / 100.0,
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
     * Rozdělí peněžní částku úhrady na složky: nejdřív splátka `target` (úrok, poplatek,
     * jistina), přebytek další otevřené splátky podle pořadí, u každé opět úrok, poplatek
     * a jistina. Stejné pořadí pak zpětně čte {@see compute}, takže rozdělení a stav
     * splátek se shodují. Částka nad otevřený zbytek všech splátek zůstane v `unallocated`
     * (volající ji odmítne jako přeplatek).
     *
     * @param list<array<string,mixed>> $coverage výstup {@see compute}
     * @return array{principal:float,interest:float,fee:float,unallocated:float}
     */
    public static function split(array $coverage, float $amount, ?int $target): array
    {
        $left = self::cents($amount);
        $taken = ['interest' => 0, 'fee' => 0, 'principal' => 0];
        $ordered = $coverage;
        usort($ordered, static fn (array $a, array $b): int =>
            [(int) $a['id'] !== $target, (int) $a['position']] <=> [(int) $b['id'] !== $target, (int) $b['position']]);
        foreach ($ordered as $row) {
            foreach (self::COMPONENTS as $component) {
                if ($left <= 0) break 2;
                $take = min($left, self::cents($row['remaining_' . $component]));
                $taken[$component] += $take;
                $left -= $take;
            }
        }
        return [
            'principal' => $taken['principal'] / 100.0,
            'interest' => $taken['interest'] / 100.0,
            'fee' => $taken['fee'] / 100.0,
            'unallocated' => $left / 100.0,
        ];
    }

    /** Má položka aspoň jednu rozdělenou splátku? */
    public static function hasSplit(array $coverage): bool
    {
        foreach ($coverage as $row) {
            if (($row['principal_amount'] ?? null) !== null) return true;
        }
        return false;
    }

    /** Peněžní zbytek všech splátek (jistina, úrok i poplatek). */
    public static function remainingTotal(array $coverage): float
    {
        $sum = 0;
        foreach ($coverage as $row) $sum += self::cents($row['remaining_amount']);
        return $sum / 100.0;
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

    /** @param array<string,mixed> $row */
    private static function isSplit(array $row): bool
    {
        return ($row['principal_amount'] ?? null) !== null;
    }

    /**
     * Složky splátky v haléřích; nerozdělená splátka je celá jistina.
     *
     * @param array<string,mixed> $row
     * @return array{interest:int,fee:int,principal:int}
     */
    private static function parts(array $row): array
    {
        if (!self::isSplit($row)) {
            return ['interest' => 0, 'fee' => 0, 'principal' => self::cents($row['amount'])];
        }
        return [
            'interest' => self::cents($row['interest_amount'] ?? 0),
            'fee' => self::cents($row['fee_amount'] ?? 0),
            'principal' => self::cents($row['principal_amount']),
        ];
    }

    private static function cents(float|int|string|null $value): int
    {
        return (int) round((float) $value * 100);
    }
}
