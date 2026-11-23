<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

/**
 * Odkaz na uplatněné dítě `dependant-<id>` (id z `payroll_dependants`).
 *
 * Seznamy dětí v potvrzení o zdanitelných příjmech, v ročním zúčtování, v roční JMHZ
 * a v evidenci nároků jdou v pořadí nároku (1., 2., 3. dítě, § 35c odst. 1 ZDP), podle
 * kterého se určuje výše zvýhodnění, a při shodném pořadí podle id dítěte
 * ({@see compareByClaimOrder()}). Odkaz se nesmí řadit jako text: `dependant-100` by
 * předběhl `dependant-99` a řádky dětí by se prohodily, jakmile id přejde na další řád.
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

    /**
     * Pořadí dětí podle pořadí nároku, při shodě podle id dítěte a nakonec podle textu
     * odkazu jiného tvaru. Neznámé pořadí (menší než 1) jde až za známá.
     */
    public static function compareByClaimOrder(int $leftOrder, string $leftReference, int $rightOrder, string $rightReference): int
    {
        return [$leftOrder >= 1 ? $leftOrder : PHP_INT_MAX, self::dependantId($leftReference) ?? PHP_INT_MAX, $leftReference]
            <=> [$rightOrder >= 1 ? $rightOrder : PHP_INT_MAX, self::dependantId($rightReference) ?? PHP_INT_MAX, $rightReference];
    }

    /**
     * Seřadí pole s klíči odkazů podle pořadí nároku ({@see compareByClaimOrder()}).
     *
     * @template T
     * @param array<array-key,T> $byReference
     * @param callable(T):int $orderOf pořadí nároku dítěte
     * @return array<array-key,T>
     */
    public static function sortByClaimOrder(array $byReference, callable $orderOf): array
    {
        uksort($byReference, static fn (int|string $left, int|string $right): int => self::compareByClaimOrder(
            $orderOf($byReference[$left]), (string) $left, $orderOf($byReference[$right]), (string) $right,
        ));
        return $byReference;
    }
}
