<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

/**
 * Kombinace pokrývající každou dvojici hodnot dvou různých dimenzí (pairwise),
 * místo plného kartézského součinu.
 *
 * Hladový algoritmus typu AETG s omezeními: dokud zbývá nepokrytá dvojice,
 * postaví několik kandidátů (každý začne nepokrytou dvojicí a ostatní dimenze
 * doplní hodnotou, která pokryje nejvíc dalších dvojic a omezení ji připustí)
 * a vezme toho, který pokryje nejvíc. Dvojice, kterou omezení nepřipustí
 * v žádné úplné kombinaci, se nepočítá. Generátor je deterministický: stejné
 * dimenze, omezení a semínko dají stejné kombinace ve stejném pořadí.
 */
final class PairwiseCombinations
{
    /**
     * @param array<string,list<string>> $dimensions dimenze => hodnoty
     * @param callable(array<string,string>):bool $allowed omezení nad ČÁSTEČNÝM
     *        přiřazením: vrací false jen tehdy, když už přiřazené hodnoty nejdou
     *        dohromady
     * @return list<array<string,string>>
     */
    public static function generate(array $dimensions, callable $allowed, int $seed = 20261009, int $candidates = 24): array
    {
        $names = array_keys($dimensions);
        $uncovered = [];
        foreach (self::pairs($dimensions) as $key => [$a, $va, $b, $vb]) {
            if ($allowed([$a => $va, $b => $vb]) && self::completable([$a => $va, $b => $vb], $dimensions, $allowed)) {
                $uncovered[$key] = true;
            }
        }
        $random = new \Random\Randomizer(new \Random\Engine\Mt19937($seed));
        $result = [];
        while ($uncovered !== []) {
            $best = null;
            $bestGain = 0;
            $firstPairs = array_keys($uncovered);
            for ($i = 0; $i < $candidates; $i++) {
                $start = $firstPairs[$i % count($firstPairs)];
                [$a, $va, $b, $vb] = self::split($start);
                $assignment = [$a => $va, $b => $vb];
                $order = array_values(array_diff($names, [$a, $b]));
                $order = $random->shuffleArray($order);
                foreach ($order as $name) {
                    $choice = null;
                    $choiceGain = -1;
                    foreach ($random->shuffleArray($dimensions[$name]) as $value) {
                        $trial = $assignment + [$name => $value];
                        if (!$allowed($trial) || !self::completable($trial, $dimensions, $allowed)) {
                            continue;
                        }
                        $gain = 0;
                        foreach ($assignment as $other => $otherValue) {
                            if (isset($uncovered[self::key($name, $value, $other, $otherValue, $names)])) {
                                $gain++;
                            }
                        }
                        if ($gain > $choiceGain) {
                            $choice = $value;
                            $choiceGain = $gain;
                        }
                    }
                    if ($choice === null) {
                        continue 2;
                    }
                    $assignment[$name] = $choice;
                }
                $gain = count(array_intersect_key($uncovered, array_flip(self::covered($assignment, $names))));
                if ($gain > $bestGain) {
                    $best = $assignment;
                    $bestGain = $gain;
                }
            }
            if ($best === null) {
                throw new \LogicException('Nepokrytou dvojici nejde doplnit na povolenou kombinaci.');
            }
            $ordered = [];
            foreach ($names as $name) {
                $ordered[$name] = $best[$name];
            }
            $result[] = $ordered;
            foreach (self::covered($ordered, $names) as $key) {
                unset($uncovered[$key]);
            }
        }

        return $result;
    }

    /**
     * Dvojice, které omezení připouští, ale žádná kombinace je nepokrývá (má být prázdné).
     *
     * @param array<string,list<string>> $dimensions
     * @param list<array<string,string>> $combinations
     * @return list<string>
     */
    public static function uncoveredPairs(array $dimensions, callable $allowed, array $combinations): array
    {
        $names = array_keys($dimensions);
        $covered = [];
        foreach ($combinations as $combination) {
            foreach (self::covered($combination, $names) as $key) {
                $covered[$key] = true;
            }
        }
        $missing = [];
        foreach (self::pairs($dimensions) as $key => [$a, $va, $b, $vb]) {
            if (!isset($covered[$key]) && $allowed([$a => $va, $b => $vb])
                && self::completable([$a => $va, $b => $vb], $dimensions, $allowed)) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * @param array<string,list<string>> $dimensions
     * @return array<string,array{0:string,1:string,2:string,3:string}>
     */
    private static function pairs(array $dimensions): array
    {
        $names = array_keys($dimensions);
        $pairs = [];
        foreach ($names as $i => $a) {
            foreach (array_slice($names, $i + 1) as $b) {
                foreach ($dimensions[$a] as $va) {
                    foreach ($dimensions[$b] as $vb) {
                        $pairs[self::key($a, $va, $b, $vb, $names)] = [$a, $va, $b, $vb];
                    }
                }
            }
        }

        return $pairs;
    }

    /**
     * Dá se částečné přiřazení doplnit na úplnou povolenou kombinaci? Prohledávání
     * do hloubky s ořezáváním; dimenzí je málo, takže je to levné.
     *
     * @param array<string,string> $assignment
     * @param array<string,list<string>> $dimensions
     */
    private static function completable(array $assignment, array $dimensions, callable $allowed): bool
    {
        foreach ($dimensions as $name => $values) {
            if (isset($assignment[$name])) {
                continue;
            }
            foreach ($values as $value) {
                $trial = $assignment + [$name => $value];
                if ($allowed($trial) && self::completable($trial, $dimensions, $allowed)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * @param array<string,string> $assignment
     * @param list<string> $names
     * @return list<string>
     */
    private static function covered(array $assignment, array $names): array
    {
        $keys = [];
        $assigned = array_keys($assignment);
        foreach ($assigned as $i => $a) {
            foreach (array_slice($assigned, $i + 1) as $b) {
                $keys[] = self::key($a, $assignment[$a], $b, $assignment[$b], $names);
            }
        }

        return $keys;
    }

    /** @param list<string> $names */
    private static function key(string $a, string $va, string $b, string $vb, array $names): string
    {
        if (array_search($a, $names, true) > array_search($b, $names, true)) {
            [$a, $va, $b, $vb] = [$b, $vb, $a, $va];
        }

        return "{$a}={$va}|{$b}={$vb}";
    }

    /** @return array{0:string,1:string,2:string,3:string} */
    private static function split(string $key): array
    {
        [$left, $right] = explode('|', $key);
        [$a, $va] = explode('=', $left, 2);
        [$b, $vb] = explode('=', $right, 2);

        return [$a, $va, $b, $vb];
    }
}
