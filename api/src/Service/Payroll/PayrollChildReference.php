<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

/**
 * Odkaz na uplatněné dítě `dependant-<id>` (id z `payroll_dependants`).
 *
 * Seznamy dětí v potvrzení o zdanitelných příjmech a v ročním zúčtování jdou v pořadí id
 * dítěte. Odkaz se nesmí řadit jako text: `dependant-100` by předběhl `dependant-99`
 * a řádky dětí by se prohodily, jakmile id přejde na další řád.
 */
final class PayrollChildReference
{
    private const PATTERN = '/^dependant-([1-9][0-9]*)$/D';

    /** Id dítěte z odkazu, nebo null, když odkaz nemá tvar `dependant-<id>`. */
    public static function dependantId(string $reference): ?int
    {
        return preg_match(self::PATTERN, $reference, $match) === 1 ? (int) $match[1] : null;
    }

    /**
     * Seřadí pole s klíči odkazů podle číselného id dítěte. Klíče jiného tvaru jdou až za ně
     * v textovém pořadí, aby bylo pořadí vždy stejné.
     *
     * @template T
     * @param array<array-key,T> $byReference
     * @return array<array-key,T>
     */
    public static function sortByDependantId(array $byReference): array
    {
        uksort($byReference, static fn (int|string $left, int|string $right): int =>
            [self::dependantId((string) $left) ?? PHP_INT_MAX, (string) $left]
            <=> [self::dependantId((string) $right) ?? PHP_INT_MAX, (string) $right]);
        return $byReference;
    }
}
